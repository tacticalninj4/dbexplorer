<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>DbExplorer</title>
@foreach ($entry['css'] as $file)
    <link rel="stylesheet" href="{{ $basePath }}/{{ $file }}">
@endforeach
@foreach ($entry['preload'] as $file)
    <link rel="modulepreload" href="{{ $basePath }}/{{ $file }}" nonce="{{ $nonce }}">
@endforeach
    <script nonce="{{ $nonce }}">window.DbExplorer = {{ \Illuminate\Support\Js::from($config) }};</script>
    <script type="module" src="{{ $basePath }}/{{ $entry['script'] }}" nonce="{{ $nonce }}"></script>
</head>
<body>
    <div id="app"></div>
</body>
</html>
