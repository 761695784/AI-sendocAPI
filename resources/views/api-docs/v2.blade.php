<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>DocTrack — Documentation API V2</title>
    <link rel="stylesheet" href="{{ l5_swagger_asset('default', 'swagger-ui.css') }}">
    <style>body { margin: 0; } .topbar { display: none; }</style>
</head>
<body>
<div id="swagger-ui"></div>
<script src="{{ l5_swagger_asset('default', 'swagger-ui-bundle.js') }}"></script>
<script src="{{ l5_swagger_asset('default', 'swagger-ui-standalone-preset.js') }}"></script>
<script>
    window.ui = SwaggerUIBundle({
        url: @json($specUrl),
        dom_id: '#swagger-ui',
        deepLinking: true,
        persistAuthorization: true,
        docExpansion: 'none',
        filter: true,
        presets: [SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset],
        layout: 'StandaloneLayout',
    });
</script>
</body>
</html>
