<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" x-data="{ darkMode: (localStorage.getItem('theme') === 'dark' || (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches)) }" :class="{ 'dark': darkMode }">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'Nova Facturación') }} - Facturación Electrónica</title>

        <!-- Assets Locales (Vite) -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <style>
            :root {
                --primary: #002aff;
                --primary-hover: #001dcc;
                --dark-bg: #0F1115;
                --dark-card: #1A1D23;
                --light-bg: #F8F9FA;
                --light-card: #FFFFFF;
                --text-light: #1F2937;
                --text-dark: #E5E7EB;
                --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            }

            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
                font-family: 'Outfit', sans-serif;
            }

            .text-primary {
                color: var(--primary);
            }
                

            body {
                background-color: var(--light-bg);
                color: var(--text-light);
                transition: var(--transition);
                overflow-x: clip; /* Mejor que hidden para evitar scroll horizontal accidental */
                width: 100%;
            }

            .dark body {
                background-color: var(--dark-bg);
                color: var(--text-dark);
            }

            /* Glassmorphism */
            .glass {
                background: rgba(255, 255, 255, 0.7);
                backdrop-filter: blur(12px);
                border: 1px solid rgba(255, 255, 255, 0.3);
                box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.07);
            }

            .dark .glass {
                background: rgba(26, 29, 35, 0.8);
                border: 1px solid rgba(255, 255, 255, 0.1);
            }

            .code-brace { color: #3454d1; font-weight: 700; }
            .code-path { color: #2847c7; }
            .code-key { color: #087443; }
            .code-value { color: #9a5200; }
            .code-comment { color: #667085; }
            .dark .code-brace { color: #9db0ff; }
            .dark .code-path { color: #dbe2ff; }
            .dark .code-key { color: #98c379; }
            .dark .code-value { color: #d19a66; }
            .dark .code-comment { color: #8b93a7; }

            /* Animations */
            @keyframes fadeInUp {
                from { opacity: 0; transform: translateY(30px); }
                to { opacity: 1; transform: translateY(0); }
            }

            .animate-fade-up {
                animation: fadeInUp 0.8s forwards ease-out;
            }

            .animate-delay-1 { animation-delay: 0.2s; }
            .animate-delay-2 { animation-delay: 0.4s; }
            .animate-delay-3 { animation-delay: 0.6s; }

            /* Hero Gradient */
            .hero-gradient {
                position: absolute;
                top: -10%;
                right: -10%;
                width: 50vw;
                height: 50vw;
                background: radial-gradient(circle, rgba(0, 42, 255, 0.15) 0%, rgba(0, 42, 255, 0) 70%);
                z-index: -1;
            }

            /* Buttons */
            .btn-primary {
                background-color: var(--primary);
                color: #fff;
                padding: 12px 28px;
                border-radius: 12px;
                font-weight: 600;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                gap: 8px;
                transition: var(--transition);
                box-shadow: 0 4px 14px 0 rgba(0, 42, 255, 0.3);
            }

            .btn-primary:hover {
                background-color: var(--primary-hover);
                transform: translateY(-2px);
                box-shadow: 0 6px 20px 0 rgba(0, 42, 255, 0.4);
            }

            .btn-outline {
                border: 2px solid var(--primary);
                color: var(--primary);
                padding: 10px 26px;
                border-radius: 12px;
                font-weight: 600;
                text-decoration: none;
                transition: var(--transition);
            }

            .btn-outline:hover {
                background-color: var(--primary);
                color: #fff;
            }

            /* Shapes */
            .floating-shape {
                position: absolute;
                background: linear-gradient(45deg, var(--primary), #4d73ff);
                opacity: 0.1;
                filter: blur(40px);
                border-radius: 50%;
                z-index: -1;
                animation: rotate 20s linear infinite;
            }

            @keyframes rotate {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }

            main {
                max-width: 1200px;
                margin: 0 auto;
                padding: 40px 12px;
                position: relative;
            }

            .nav-container {
                position: fixed;
                top: 0; left: 0; right: 0;
                padding: 8px 16px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                z-index: 1000;
            }

            .logo {
                display: flex;
                align-items: center;
                gap: 6px;
                font-size: 1.1rem;
                font-weight: 700;
                color: inherit;
                text-decoration: none;
            }

            .logo span { color: var(--primary); }

            .nav-links {
                display: flex;
                gap: 12px;
                align-items: center;
            }

            .nav-link {
                display: none; /* Hide on XS */
                text-decoration: none;
                color: inherit;
                font-weight: 500;
                opacity: 0.8;
                font-size: 0.9rem;
            }

            @media (min-width: 640px) {
                .nav-link { display: block; }
                .nav-links { gap: 24px; }
                .nav-container { padding: 12px 32px; }
                .logo { font-size: 1.3rem; gap: 10px; }
            }

            .theme-toggle {
                cursor: pointer;
                background: none;
                border: none;
                padding: 6px;
                border-radius: 8px;
                display: flex;
                color: inherit;
            }

            /* Hero Section - Mobile First */
            .hero {
                display: grid;
                grid-template-columns: 1fr;
                gap: 16px;
                align-items: center;
                text-align: center;
                padding-top: 20px;
            }

            .hero h1 {
                font-size: 1.5rem;
                line-height: 1.1;
                margin-bottom: 12px;
                font-weight: 700;
            }

            .hero p {
                font-size: 0.85rem;
                opacity: 0.7;
                margin-bottom: 24px;
                line-height: 1.4;
            }

            @media (min-width: 768px) {
                .hero { grid-template-columns: 1.2fr 0.8fr; text-align: left; gap: 40px; }
                .hero h1 { font-size: 3rem; }
                .hero p { font-size: 1rem; }
                main { padding: 80px 24px; }
            }

            /* Badge */
            .badge {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                background: rgba(0, 42, 255, 0.14);
                color: var(--primary);
                padding: 4px 12px;
                border-radius: 100px;
                font-size: 0.75rem;
                font-weight: 600;
                margin-bottom: 12px;
            }

            /* Cards */
            .features {
                display: grid;
                grid-template-columns: 1fr;
                gap: 16px;
                margin-top: 40px;
            }

            @media (min-width: 640px) {
                .features { grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 24px; }
            }

            .card {
                padding: 20px;
                border-radius: 16px;
                transition: var(--transition);
            }

            .card:hover { transform: translateY(-5px); }

            .card-icon {
                width: 48px; height: 48px;
                background: rgba(248, 184, 3, 0.1);
                color: var(--primary);
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 12px;
                margin-bottom: 16px;
            }

            .card h3 { font-size: 1.25rem; margin-bottom: 12px; }
            .card p { font-size: 0.95rem; opacity: 0.7; line-height: 1.4; }

            /* Footer */
            footer {
                padding: 60px 24px;
                text-align: center;
                opacity: 0.5;
                font-size: 0.875rem;
            }

            /* SVGs */
            .hero-img-svg {
                width: 100%;
                filter: drop-shadow(0 20px 40px rgba(0,0,0,0.1));
            }
        </style>
    </head>
    <body :class="{ 'dark': darkMode }" style="position: relative; min-height: 100vh; overflow-x: hidden;">
        <div class="overflow-wrapper" style="position: absolute; inset: 0; overflow: hidden; pointer-events: none; z-index: -1;">
            <div class="hero-gradient"></div>
            <div class="floating-shape" style="width: 300px; height: 300px; top: 20%; left: -10%;"></div>
        </div>

        <!-- Navigation -->
        <nav class="nav-container glass">
            <a href="/" class="logo">
                <svg width="40" height="40" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect width="40" height="40" rx="12" fill="var(--primary)"/>
                    <path d="M12 28V12H28V16H16V28H12Z" fill="white"/>
                    <path d="M20 28V20H28V28H20Z" fill="white" opacity="0.6"/>
                </svg>
                Nova <span>Facturación</span>
            </a>

            <div class="nav-links">
                <a href="/docs" class="nav-link">Documentación</a>
                <a href="#" class="nav-link">Soporte</a>
                
                @if (Route::has('login'))
                    <div class="flex items-center gap-4">
                        @auth
                            <a href="{{ url('/dashboard') }}" class="nav-link font-semibold">Panel Control</a>
                        @else
                            <a href="{{ route('login') }}" class="nav-link">Ingresar</a>
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="btn-primary" style="padding: 8px 20px; border-radius: 10px;">Empezar</a>
                            @endif
                        @endauth
                    </div>
                @endif

                <button @click="darkMode = !darkMode; localStorage.setItem('theme', darkMode ? 'dark' : 'light')" class="theme-toggle">
                    <template x-if="!darkMode">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
                    </template>
                    <template x-if="darkMode">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
                    </template>
                </button>
            </div>
        </nav>

        <main>
            <!-- Hero Section -->
            <section class="hero">
                <div class="hero-content animate-fade-up">
                    <div class="badge">
                        <span class="flex h-2 w-2 rounded-full bg-blue-500 animate-pulse"></span>
                        UBL v2.1 disponible ahora
                    </div>
                    <h1>
                        <span class="text-primary">Nova</span> 
                        <br>
                        Emisión de Comprobantes</h1>
                    <p>La infraestructura API más robusta para facturación electrónica y guías de remisión en Perú. Integración sencilla, validación instantánea y alta disponibilidad.</p>
                    
                    <!-- <div class="hero-actions">
                        <a href="/docs" class="btn-primary">
                            Explorar la API
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                        </a>
                        <a href="#features" class="btn-outline">Ver planes</a>
                    </div> -->
                </div>

                <div class="hero-image animate-fade-up animate-delay-1" style="width: 100%; max-width: 100%; overflow: hidden;">
                    <!-- Glassmorphism Card Mockup -->
                    <div class="glass" style="padding: 24px; border-radius: 20px; width: 100%; max-width: 100%; overflow-x: auto;">
                        <div style="display: flex; gap: 8px; margin-bottom: 20px;">
                            <div style="width: 12px; height: 12px; border-radius: 50%; background: #ff5f56;"></div>
                            <div style="width: 12px; height: 12px; border-radius: 50%; background: #ffbd2e;"></div>
                            <div style="width: 12px; height: 12px; border-radius: 50%; background: #27c93f;"></div>
                        </div>
                        <code style="font-family: 'Courier New', monospace; font-size: 14px; color: var(--primary);">
                            <span style="color: #3454d1; font-weight: 700;">POST</span> <span class="code-path">/api/facturacion/emitir-factura</span><br>
                            <span class="code-brace">{</span><br>
                            &nbsp;&nbsp;<span class="code-key">"serie"</span>: <span class="code-value">"F001"</span>,<br>
                            &nbsp;&nbsp;<span class="code-key">"correlativo"</span>: <span class="code-value">"102"</span>,<br>
                            &nbsp;&nbsp;<span class="code-key">"cliente"</span>: <span class="code-brace">{ ... }</span><br>
                            <span class="code-brace">}</span><br><br>
                            <span class="code-comment">// Response</span><br>
                            <span class="code-brace">{</span><br>
                            &nbsp;&nbsp;<span class="code-key">"success"</span>: <span class="code-value">true</span>,<br>
                            &nbsp;&nbsp;<span class="code-key">"message"</span>: <span class="code-value">"ACEPTADA"</span><br>
                            <span class="code-brace">}</span>
                        </code>
                    </div>
                </div>
            </section>

            <!-- Features -->
            <section id="features" class="features">
                <div class="card glass animate-fade-up animate-delay-1">
                    <div class="card-icon">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    </div>
                    <h3>Facturación CPE</h3>
                    <p>Emite facturas, boletas, notas de crédito y débito cumpliendo con todos los estándares UBL 2.1.</p>
                </div>

                <div class="card glass animate-fade-up animate-delay-2">
                    <div class="card-icon">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
                    </div>
                    <h3>Guías REST (GRE)</h3>
                    <p>Integración nativa con la nueva API GRE de SUNAT para guías de remisión remitente y transportista.</p>
                </div>

                <div class="card glass animate-fade-up animate-delay-3">
                    <div class="card-icon">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                    </div>
                    <h3>Validación Previa</h3>
                    <p>Evita rechazos con nuestro motor de validación local que detecta errores antes de enviarlos a SUNAT.</p>
                </div>
            </section>
        </main>

        <footer>
            &copy; {{ date('Y') }} Nova Facturación Electrónica. Todos los derechos reservados.<br>
            Desarrollado para la excelencia operativa en facturación electrónica.
        </footer>
    </body>
</html>
