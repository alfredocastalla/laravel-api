<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>BarangayCare</title>
    <link rel="stylesheet" href="{{ route('frontend.app-stylesheet') }}" />
    <link rel="manifest" href="manifest.webmanifest" />
    <meta name="theme-color" content="#7653d9" />
  </head>
  <body>
    <div id="app"></div>

    <div id="icon-set" aria-hidden="true">
      <svg xmlns="http://www.w3.org/2000/svg" style="display:none">
        <symbol id="i-dashboard" viewBox="0 0 24 24"><path d="M3 13h8V3H3v10Zm0 8h8v-6H3v6Zm10 0h8V11h-8v10Zm0-18v6h8V3h-8Z"/></symbol>
        <symbol id="i-users" viewBox="0 0 24 24"><path d="M16 11c1.66 0 3-1.57 3-3.5S17.66 4 16 4s-3 1.57-3 3.5 1.34 3.5 3 3.5ZM8 11c1.66 0 3-1.57 3-3.5S9.66 4 8 4 5 5.57 5 7.5 6.34 11 8 11Zm0 2c-2.67 0-6 1.34-6 4v2h12v-2c0-2.66-3.33-4-6-4Zm8 0c-.31 0-.65.02-1 .07 1.16.84 2 1.96 2 3.43V19h5v-2c0-2.66-3.33-4-6-4Z"/></symbol>
        <symbol id="i-calendar" viewBox="0 0 24 24"><path d="M7 2h2v3h6V2h2v3h4v17H3V5h4V2Zm12 8H5v10h14V10ZM5 8h14V7H5v1Z"/></symbol>
        <symbol id="i-aid" viewBox="0 0 24 24"><path d="M20 6h-4.18A3 3 0 0 0 10 5a3 3 0 0 0-5.82 1H4a2 2 0 0 0-2 2v3h20V8a2 2 0 0 0-2-2Zm-9 0a1 1 0 1 1-1-1 1 1 0 0 1 1 1Zm3 0a1 1 0 1 1 1 1h-1V6ZM3 13v7a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7h-8v9h-2v-9H3Z"/></symbol>
        <symbol id="i-report" viewBox="0 0 24 24"><path d="M6 2h9l5 5v15H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Zm8 1.5V8h4.5L14 3.5ZM8 12h8v2H8v-2Zm0 4h8v2H8v-2Zm0-8h4v2H8V8Z"/></symbol>
        <symbol id="i-settings" viewBox="0 0 24 24"><path d="M19.43 12.98c.04-.32.07-.65.07-.98s-.02-.66-.07-.98l2.11-1.65-2-3.46-2.49 1a7.28 7.28 0 0 0-1.69-.98L15 3h-4l-.36 2.93c-.6.23-1.16.56-1.69.98l-2.49-1-2 3.46 2.11 1.65c-.04.32-.07.65-.07.98s.02.66.07.98l-2.11 1.65 2 3.46 2.49-1c.52.4 1.09.73 1.69.98L11 21h4l.36-2.93c.6-.23 1.16-.56 1.69-.98l2.49 1 2-3.46-2.11-1.65ZM13 15.5A3.5 3.5 0 1 1 13 8a3.5 3.5 0 0 1 0 7.5Z"/></symbol>
        <symbol id="i-lock" viewBox="0 0 24 24"><path d="M17 8V6a5 5 0 0 0-10 0v2H5v14h14V8h-2Zm-8 0V6a3 3 0 0 1 6 0v2H9Zm4 9.73V19h-2v-1.27A2 2 0 1 1 13 16a2 2 0 0 1 0 1.73Z"/></symbol>
        <symbol id="i-search" viewBox="0 0 24 24"><path d="m21 19.59-5.29-5.3A7.92 7.92 0 0 0 17 10a8 8 0 1 0-8 8 7.92 7.92 0 0 0 4.29-1.29l5.3 5.29L21 19.59ZM4 10a6 6 0 1 1 6 6 6 6 0 0 1-6-6Z"/></symbol>
        <symbol id="i-message" viewBox="0 0 24 24"><path d="M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H8l-6 4V6a2 2 0 0 1 2-2Zm0 2v12.26L7.4 16H20V6H4Zm3 3h10v2H7V9Zm0 4h7v2H7v-2Z"/></symbol>
        <symbol id="i-logout" viewBox="0 0 24 24"><path d="M10 17v-3H3v-4h7V7l5 5-5 5Zm9-14H9a2 2 0 0 0-2 2v3h2V5h10v14H9v-3H7v3a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2Z"/></symbol>
      </svg>
    </div>

    <script src="vendor/react.production.min.js"></script>
    <script src="vendor/react-dom.production.min.js"></script>
    <script src="wamp-config.js"></script>
<script src="laravel-service.js"></script>
    <script src="app.js?v=20261005-laravel2"></script>
  </body>
</html>
