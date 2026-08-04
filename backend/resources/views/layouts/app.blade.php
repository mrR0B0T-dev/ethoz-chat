{{-- Kerangka bersama seluruh tampilan Blade Ethoz Chat.
     Di sinilah SATU-SATUNYA tempat lembar gaya ditautkan — tampilan lain
     cukup @extends('layouts.app') dan tidak menautkannya lagi.

     Bagian yang tersedia:
       head        : meta khusus halaman (csrf, theme-color, favicon)
       title       : isi <title>
       body-class  : kelas pelingkup pada <body>, mis. "page-console"
       content     : isi halaman
       scripts     : @push('scripts') untuk skrip halaman --}}
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @yield('head')
    <title>@yield('title', 'Ethoz Chat')</title>
    @vite(['resources/css/app.css'])
</head>

<body class="@yield('body-class')">
    @yield('content')
    @stack('scripts')
</body>

</html>
