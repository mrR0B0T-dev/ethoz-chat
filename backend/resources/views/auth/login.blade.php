{{-- Login sesi minimal untuk backend mandiri. Di produksi, sesi berasal dari
     aplikasi Ethoz yang sudah ada — lihat IMPLEMENTATION.md.
     Seluruh gaya berada di resources/css/app.css (bagian .page-login). --}}
@extends('layouts.app')

@section('title', 'Masuk — Ethoz Chat')
@section('body-class', 'page-login')

@section('content')
  <form class="card" method="POST" action="{{ route('login.attempt') }}">
    @csrf
    <div class="brand">
      <div class="dot">✦</div>
      <div>
        <h1>Ethoz Chat</h1>
        <small>Masuk untuk membuka konsol admin</small>
      </div>
    </div>

    @if ($errors->any())
      <div class="err">
        <ul>
          @foreach ($errors->all() as $error)
            <li>{{ $error }}</li>
          @endforeach
        </ul>
      </div>
    @endif

    <label class="lbl" for="email">Email</label>
    <input class="in" id="email" type="email" name="email"
           value="{{ old('email') }}" required autofocus autocomplete="username">

    <label class="lbl" for="password">Kata sandi</label>
    <input class="in" id="password" type="password" name="password"
           required autocomplete="current-password">

    <label class="remember">
      <input type="checkbox" name="remember" value="1"> Ingat saya
    </label>

    <button class="btn" type="submit">Masuk</button>
  </form>
@endsection
