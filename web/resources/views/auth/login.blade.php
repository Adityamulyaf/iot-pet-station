<x-layouts.app title="Masuk">
<div class="login-page">
    <div class="login-story">
        <a href="{{ route('login') }}" class="brand"><span class="brand-mark"><x-icon name="grid"/></span>petstation<span class="brand-period">.</span></a>
        <div class="login-copy"><span class="eyebrow">KEBUTUHAN KECIL, PERHATIAN SETIAP HARI</span><h1>Selalu dekat.<br>Di mana pun<br>Anda berada.</h1><p>Pantau persediaan pakan dan air, kendalikan station, dan lihat setiap aktivitas dalam satu tempat.</p>
            <div class="login-illustration" aria-hidden="true"><div class="vessel food-vessel"><div class="vessel-fill"></div><div class="vessel-line"></div></div><div class="vessel water-vessel"><div class="vessel-fill"></div><div class="vessel-line"></div></div><div class="vessel-base"></div></div>
        </div>
        <span class="login-foot">Smart Pet Feeder & Hydration Station</span>
    </div>
    <main class="login-form-area"><div class="login-form-box">
        <span class="badge neutral"><span class="dot"></span> Workspace lokal</span>
        <h2>Selamat datang kembali</h2><p class="muted">Masuk untuk memantau station Anda.</p>
        <form method="POST" action="{{ route('login.submit') }}" class="login-form">
            @csrf
            <label for="email">Email</label><input id="email" name="email" type="email" value="{{ old('email') }}" placeholder="nama@email.com" required autofocus autocomplete="username">
            <label for="password">Kata sandi</label><input id="password" name="password" type="password" placeholder="Masukkan kata sandi" required autocomplete="current-password">
            @if($errors->any())<p class="form-error" role="alert">{{ $errors->first() }}</p>@endif
            <label class="checkbox-label"><input type="checkbox" name="remember" value="1"> Ingat saya</label>
            <button class="button primary wide" type="submit">Masuk ke workspace <x-icon name="arrow"/></button>
        </form>
        @if(app()->environment('local'))<div class="demo-hint"><span class="eyebrow">AKUN DEMO LOKAL</span><p>demo@petstation.local<br><span class="mono">petstation-local</span></p></div>@endif
        <p class="login-privacy"><x-icon name="shield"/> Akses station dibatasi untuk pemilik akun.</p>
    </div></main>
</div>
</x-layouts.app>
