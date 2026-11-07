<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laravel | BarangayCare</title>
    <style>
      :root {
        color-scheme: light;
        --ink: #172033;
        --muted: #5d677c;
        --line: #dfe5ef;
        --brand: #7653d9;
        --brand-dark: #5133a8;
        --green: #16834a;
        --bg: #f6f8fc;
        --panel: #ffffff;
      }
      * { box-sizing: border-box; }
      body {
        margin: 0;
        min-height: 100vh;
        font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        color: var(--ink);
        background: linear-gradient(135deg, #f7f4ff 0%, #f6f8fc 44%, #eefbf5 100%);
      }
      main {
        width: min(1080px, calc(100% - 32px));
        margin: 0 auto;
        padding: 48px 0;
      }
      .topbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 16px;
        margin-bottom: 48px;
      }
      .brand {
        display: flex;
        align-items: center;
        gap: 12px;
        font-weight: 800;
        font-size: 1.08rem;
      }
      .mark {
        display: grid;
        place-items: center;
        width: 42px;
        height: 42px;
        border-radius: 8px;
        background: var(--brand);
        color: #fff;
        font-weight: 900;
      }
      .actions { display: flex; gap: 10px; flex-wrap: wrap; }
      a {
        color: inherit;
        text-decoration: none;
      }
      .button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 40px;
        padding: 0 16px;
        border: 1px solid var(--line);
        border-radius: 8px;
        background: #fff;
        color: var(--ink);
        font-weight: 700;
      }
      .button.primary {
        border-color: var(--brand);
        background: var(--brand);
        color: #fff;
      }
      .hero {
        display: grid;
        grid-template-columns: minmax(0, 1.1fr) minmax(280px, 0.9fr);
        gap: 24px;
        align-items: stretch;
      }
      .intro, .panel {
        background: rgba(255, 255, 255, 0.86);
        border: 1px solid rgba(223, 229, 239, 0.9);
        border-radius: 8px;
        box-shadow: 0 20px 60px rgba(31, 41, 55, 0.08);
      }
      .intro { padding: clamp(28px, 5vw, 52px); }
      .eyebrow {
        display: inline-flex;
        padding: 7px 10px;
        border-radius: 999px;
        background: #eefbf5;
        color: var(--green);
        font-size: 0.78rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.08em;
      }
      h1 {
        margin: 20px 0 14px;
        font-size: clamp(2.4rem, 6vw, 5rem);
        line-height: 0.96;
        letter-spacing: 0;
      }
      p {
        color: var(--muted);
        font-size: 1.04rem;
        line-height: 1.7;
        margin: 0;
      }
      .hero-actions { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 28px; }
      .panel { padding: 24px; }
      .panel h2 { margin: 0 0 16px; font-size: 1.1rem; }
      .status-list { display: grid; gap: 12px; }
      .status-item {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        padding: 14px;
        border: 1px solid var(--line);
        border-radius: 8px;
        background: #fff;
      }
      .status-item span { color: var(--muted); }
      .status-item strong { text-align: right; }
      .footer-note {
        margin-top: 28px;
        padding-top: 18px;
        border-top: 1px solid var(--line);
        color: var(--muted);
        font-size: 0.95rem;
      }
      @media (max-width: 760px) {
        .topbar, .hero { display: block; }
        .actions { margin-top: 16px; }
        .panel { margin-top: 18px; }
      }
    </style>
  </head>
  <body>
    <main>
      <nav class="topbar" aria-label="Laravel page navigation">
        <div class="brand"><span class="mark">L</span><span>Laravel Backend</span></div>
        <div class="actions">
          <a class="button" href="{{ url('/') }}">BarangayCare App</a>
          <a class="button primary" href="{{ url('/api/health.php') }}">API Health</a>
        </div>
      </nav>

      <section class="hero">
        <div class="intro">
          <span class="eyebrow">Laravel is active</span>
          <h1>BarangayCare Backend</h1>
          <p>This page confirms the Laravel application is serving your system. The main BarangayCare interface is still available on the home route, while this page gives you a clean Laravel status page.</p>
          <div class="hero-actions">
            <a class="button primary" href="{{ url('/') }}">Open System</a>
            <a class="button" href="{{ url('/app') }}">Open App Route</a>
          </div>
        </div>

        <aside class="panel" aria-label="Application details">
          <h2>Application Details</h2>
          <div class="status-list">
            <div class="status-item"><span>App name</span><strong>{{ config('app.name') }}</strong></div>
            <div class="status-item"><span>Environment</span><strong>{{ config('app.env') }}</strong></div>
            <div class="status-item"><span>Debug mode</span><strong>{{ config('app.debug') ? 'On' : 'Off' }}</strong></div>
            <div class="status-item"><span>Database</span><strong>{{ config('database.default') }}</strong></div>
            <div class="status-item"><span>API prefix</span><strong>/api</strong></div>
          </div>
          <p class="footer-note">Use this page when you want to confirm Laravel is running separately from the React frontend.</p>
        </aside>
      </section>
    </main>
  </body>
</html>