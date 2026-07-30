{{-- Login sesi minimal untuk backend mandiri. Di produksi, sesi berasal dari
     aplikasi Ethoz yang sudah ada — lihat IMPLEMENTATION.md. --}}
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Masuk — Ethoz Chat</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Inter:wght@400;500;600&display=swap');
    :root{
      --navy:#031334; --blue:#004A7B; --teal:#00796E; --accTeal:#00A88F;
      --mint:#00F6A5; --ink:#0B1B2B; --muted:#6B7B87;
    }
    *{box-sizing:border-box}
    body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
      padding:24px;background:linear-gradient(165deg,#E9F6F3,#F4FAF9 55%,#EAF4FB);
      font-family:'Inter',system-ui,sans-serif;color:var(--ink)}
    .card{width:100%;max-width:380px;background:#fff;border-radius:18px;padding:26px;
      border:1px solid rgba(3,19,52,.06);box-shadow:0 6px 18px rgba(3,19,52,.05)}
    .brand{display:flex;align-items:center;gap:10px;margin-bottom:18px}
    .brand .dot{width:38px;height:38px;border-radius:11px;display:flex;align-items:center;
      justify-content:center;background:linear-gradient(135deg,#00F6A5,#00A88F);font-size:19px}
    .brand h1{font-family:'Fredoka',sans-serif;font-weight:600;font-size:17px;margin:0;line-height:1.15;color:var(--navy)}
    .brand small{font-size:11.5px;color:var(--muted)}
    label.lbl{display:block;font-size:12.5px;font-weight:600;margin:12px 0 5px}
    .in{width:100%;font-family:'Inter',sans-serif;font-size:13.5px;color:var(--ink);
      padding:10px 12px;border-radius:11px;border:1px solid #DDE8E5;outline:none;background:#fff}
    .in:focus{border-color:var(--accTeal);box-shadow:0 0 0 3px rgba(0,168,143,.15)}
    .btn{margin-top:18px;width:100%;border:none;border-radius:11px;font-family:'Inter',sans-serif;
      font-weight:600;cursor:pointer;color:#fff;padding:11px 16px;font-size:13.5px;
      background:linear-gradient(135deg,#00A88F,#004A7B)}
    .err{margin:14px 0 0;padding:10px 12px;border-radius:11px;background:#FDF3F3;
      border:1px solid #F0D8D8;color:#C0554F;font-size:12.5px;line-height:1.45}
    .err ul{margin:0;padding-left:16px}
    .remember{display:flex;align-items:center;gap:7px;margin-top:14px;font-size:12.5px;color:var(--muted)}
  </style>
</head>
<body>
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
</body>
</html>
